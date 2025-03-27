
// This file contains scraper utilities for the obituary scraper
// In a real implementation, this would handle the actual scraping logic

import { Obituary } from "./dataUtils";

interface ScraperConfig {
  regions: string[];
  frequency: string;
  maxAge: number;
  filterKeywords?: string[];
  startDate?: string;
  endDate?: string;
}

export const DEFAULT_SCRAPER_CONFIG: ScraperConfig = {
  regions: ["Toronto", "Ottawa", "Hamilton", "London", "Windsor"],
  frequency: "daily",
  maxAge: 7,
};

// Mock function to simulate scraping obituaries from sources
export const scrapeObituaries = async (
  config: ScraperConfig = DEFAULT_SCRAPER_CONFIG
): Promise<{ success: boolean; data: Obituary[] | null; message: string }> => {
  console.log("Scraping obituaries with config:", config);
  
  // This is a simulation - in a real implementation, this would:
  // 1. Connect to various funeral home websites in Ontario
  // 2. Parse the HTML to extract obituary data
  // 3. Clean and standardize the data
  // 4. Return the structured data
  
  // Simulate API delay
  await new Promise((resolve) => setTimeout(resolve, 3000));
  
  // Simulation: 90% chance of success
  const isSuccess = Math.random() > 0.1;
  
  if (isSuccess) {
    // In a real implementation, this would be actual scraped data
    return {
      success: true,
      data: [], // Would contain newly scraped obituaries
      message: `Successfully scraped obituaries from ${config.regions.join(", ")}`
    };
  } else {
    return {
      success: false,
      data: null,
      message: "Error connecting to one or more sources. Please try again later."
    };
  }
};

// Function to format scraped data for WordPress
export const formatForWordPress = (obituaries: Obituary[]): unknown => {
  // This would transform the data into a format compatible with WordPress posts
  // For example, converting to WP post objects with custom fields
  
  return obituaries.map(obit => ({
    post_title: obit.name,
    post_content: obit.description,
    post_status: "publish",
    post_type: "obituary", // Custom post type
    meta: {
      obituary_date_of_death: obit.dateOfDeath,
      obituary_date_of_birth: obit.dateOfBirth || "",
      obituary_age: obit.age || "",
      obituary_funeral_home: obit.funeralHome,
      obituary_location: obit.location,
      obituary_source_url: obit.sourceUrl,
    }
  }));
};

// Function to scrape obituaries from the previous month
export const scrapePreviousMonthObituaries = async (
  config: ScraperConfig = DEFAULT_SCRAPER_CONFIG
): Promise<{ success: boolean; data: Obituary[] | null; message: string }> => {
  console.log("Scraping previous month obituaries...");
  
  // Calculate date range for previous month
  const today = new Date();
  const lastMonth = new Date(today);
  lastMonth.setMonth(today.getMonth() - 1);
  
  const startDate = new Date(lastMonth.getFullYear(), lastMonth.getMonth(), 1);
  const endDate = new Date(today.getFullYear(), today.getMonth(), 0);
  
  // Update config with date range
  const previousMonthConfig: ScraperConfig = {
    ...config,
    startDate: startDate.toISOString().split('T')[0],
    endDate: endDate.toISOString().split('T')[0],
  };
  
  console.log("Previous month date range:", previousMonthConfig.startDate, "to", previousMonthConfig.endDate);
  
  // Simulate API delay
  await new Promise((resolve) => setTimeout(resolve, 3000));
  
  // Mock data generation for the previous month
  if (Math.random() > 0.1) { // 90% success rate
    // Generate mock obituaries for the previous month
    const mockObituaries = generateMockObituariesForDateRange(
      startDate, 
      endDate, 
      previousMonthConfig.regions
    );
    
    return {
      success: true,
      data: mockObituaries,
      message: `Successfully scraped ${mockObituaries.length} obituaries from the previous month (${previousMonthConfig.startDate} to ${previousMonthConfig.endDate})`
    };
  } else {
    return {
      success: false,
      data: null,
      message: "Error retrieving previous month obituaries. Please try again later."
    };
  }
};

// Helper function to generate mock obituary data for a date range
const generateMockObituariesForDateRange = (
  startDate: Date,
  endDate: Date,
  regions: string[]
): Obituary[] => {
  const obituaries: Obituary[] = [];
  const dayCount = Math.floor((endDate.getTime() - startDate.getTime()) / (24 * 60 * 60 * 1000));
  
  // Names for generating random obituaries
  const firstNames = [
    "James", "Mary", "John", "Patricia", "Robert", "Jennifer", "Michael", "Linda", 
    "William", "Elizabeth", "David", "Barbara", "Richard", "Susan", "Joseph", "Jessica",
    "Thomas", "Sarah", "Charles", "Karen", "Christopher", "Margaret", "Daniel", "Nancy",
    "Matthew", "Lisa", "Anthony", "Betty", "Donald", "Dorothy", "Mark", "Sandra"
  ];
  
  const lastNames = [
    "Smith", "Johnson", "Williams", "Brown", "Jones", "Miller", "Davis", "Garcia", 
    "Rodriguez", "Wilson", "Martinez", "Anderson", "Taylor", "Thomas", "Hernandez", "Moore",
    "Martin", "Jackson", "Thompson", "White", "Lopez", "Lee", "Gonzalez", "Harris",
    "Clark", "Lewis", "Robinson", "Walker", "Perez", "Hall", "Young", "Allen"
  ];
  
  const funeralHomes = {
    "Toronto": ["Toronto Memorial", "City Funeral Home", "Highland Funeral Home"],
    "Ottawa": ["Ottawa Memorial", "Capital Funeral Services", "Rideau Funeral Home"],
    "Hamilton": ["Hamilton Memorial Gardens", "Mountain Funeral Home", "Bayview Services"],
    "London": ["London Memorial", "Forest City Funeral Home", "Woodland Cremation"],
    "Windsor": ["Windsor Memorial Gardens", "Riverside Funeral Services", "LaSalle Funeral Home"]
  };
  
  // Generate 2-5 obituaries per day
  for (let i = 0; i < dayCount; i++) {
    const date = new Date(startDate);
    date.setDate(startDate.getDate() + i);
    
    // Generate 2-5 obituaries for this day
    const dailyCount = Math.floor(Math.random() * 4) + 2;
    
    for (let j = 0; j < dailyCount; j++) {
      const region = regions[Math.floor(Math.random() * regions.length)];
      const firstName = firstNames[Math.floor(Math.random() * firstNames.length)];
      const lastName = lastNames[Math.floor(Math.random() * lastNames.length)];
      
      const age = Math.floor(Math.random() * 50) + 40; // Ages 40-90
      const birthYear = date.getFullYear() - age;
      const birthMonth = Math.floor(Math.random() * 12);
      const birthDay = Math.floor(Math.random() * 28) + 1;
      const dateOfBirth = `${birthYear}-${(birthMonth + 1).toString().padStart(2, '0')}-${birthDay.toString().padStart(2, '0')}`;
      
      const regionalFuneralHomes = funeralHomes[region as keyof typeof funeralHomes] || ["Memorial Services"];
      const funeralHome = regionalFuneralHomes[Math.floor(Math.random() * regionalFuneralHomes.length)];
      
      obituaries.push({
        id: `mock-${date.getTime()}-${j}`,
        name: `${firstName} ${lastName}`,
        age: age,
        dateOfBirth: dateOfBirth,
        dateOfDeath: date.toISOString().split('T')[0],
        funeralHome: funeralHome,
        location: region,
        description: generateObituaryDescription(firstName, lastName, age, region),
        sourceUrl: `https://example.com/obituaries/${lastName.toLowerCase()}-${firstName.toLowerCase()}`,
        imageUrl: Math.random() > 0.3 ? `https://randomuser.me/api/portraits/${Math.random() > 0.5 ? 'men' : 'women'}/${Math.floor(Math.random() * 70)}.jpg` : undefined
      });
    }
  }
  
  return obituaries;
};

// Generate a realistic obituary description
const generateObituaryDescription = (firstName: string, lastName: string, age: number, location: string): string => {
  const templates = [
    `It is with great sadness that the family of {firstName} {lastName} announces their passing on {deathDate} at the age of {age}. {firstName} will be lovingly remembered by their family and friends in {location} and surrounding areas.`,
    
    `{firstName} {lastName}, age {age}, passed away peacefully surrounded by loved ones. Born and raised in {location}, {firstName} was known for their kindness and generosity to all who knew them.`,
    
    `The family announces with sorrow the death of {firstName} {lastName} of {location} at the age of {age}. {firstName} leaves behind many friends and family members who will deeply miss their presence in their lives.`,
    
    `After a life well-lived, {firstName} {lastName} passed away at the age of {age}. A long-time resident of {location}, {firstName} was active in the community and will be remembered for their contributions to local charities.`,
    
    `{firstName} {lastName} passed away peacefully at home at the age of {age}. Born in {location}, {firstName} was a beloved member of the community and will be deeply missed by all who knew them.`
  ];
  
  const template = templates[Math.floor(Math.random() * templates.length)];
  return template
    .replace(/{firstName}/g, firstName)
    .replace(/{lastName}/g, lastName)
    .replace(/{age}/g, age.toString())
    .replace(/{location}/g, location)
    .replace(/{deathDate}/g, new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }));
};

// Function to determine if an obituary is a duplicate
export const isDuplicate = (
  newObituary: Partial<Obituary>,
  existingObituaries: Obituary[]
): boolean => {
  return existingObituaries.some(existing => {
    // Check for duplication based on name and date of death
    const nameMatch = existing.name.toLowerCase() === newObituary.name?.toLowerCase();
    const dateMatch = existing.dateOfDeath === newObituary.dateOfDeath;
    
    return nameMatch && dateMatch;
  });
};

// Function to validate obituary data
export const validateObituary = (
  obituary: Partial<Obituary>
): { isValid: boolean; errors: string[] } => {
  const errors: string[] = [];
  
  if (!obituary.name) {
    errors.push("Missing name");
  }
  
  if (!obituary.dateOfDeath) {
    errors.push("Missing date of death");
  }
  
  if (!obituary.location) {
    errors.push("Missing location");
  }
  
  if (!obituary.funeralHome) {
    errors.push("Missing funeral home");
  }
  
  if (!obituary.description) {
    errors.push("Missing description");
  }
  
  return {
    isValid: errors.length === 0,
    errors
  };
};
