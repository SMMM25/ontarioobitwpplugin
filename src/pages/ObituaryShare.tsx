
import { useEffect, useState } from "react";
import { useParams, Link } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import Header from "@/components/Header";
import { Skeleton } from "@/components/ui/skeleton";

// Function to fetch obituary details
const fetchObituaryDetails = async (id: string) => {
  // This would normally be an API call to your WordPress site
  // For now, we'll just return some placeholder data
  // In a real implementation, this would connect to the WordPress REST API
  return {
    id,
    name: "Sample Name", // This would be filled in from the real API
    age: 85,
    dateOfDeath: "2023-08-15",
    funeralHome: "Example Funeral Home",
    location: "Toronto, ON",
    imageUrl: "https://placehold.co/400",
    description: "This is a sample obituary description. The real data would come from your WordPress site's database.",
    sourceUrl: "#"
  };
};

const ObituaryShare = () => {
  const { id } = useParams<{ id: string }>();
  
  useEffect(() => {
    document.title = "Obituary Details | Ontario Obituaries";
  }, []);

  const { data: obituary, isLoading, error } = useQuery({
    queryKey: ['obituary', id],
    queryFn: () => fetchObituaryDetails(id || ""),
    enabled: !!id
  });

  if (error) {
    return (
      <div className="min-h-screen">
        <Header />
        <main className="pt-32 pb-16 container mx-auto px-4">
          <Card className="max-w-2xl mx-auto">
            <CardHeader>
              <CardTitle className="text-red-500">Error</CardTitle>
            </CardHeader>
            <CardContent>
              <p>Unable to load obituary details. The obituary may have been removed or is no longer available.</p>
            </CardContent>
            <CardFooter>
              <Button asChild>
                <Link to="/">Return to Home</Link>
              </Button>
            </CardFooter>
          </Card>
        </main>
      </div>
    );
  }

  return (
    <div className="min-h-screen">
      <Header />
      
      <main className="pt-32 pb-16 container mx-auto px-4">
        <Card className="max-w-2xl mx-auto">
          {isLoading ? (
            <>
              <CardHeader>
                <Skeleton className="h-8 w-3/4" />
                <Skeleton className="h-4 w-1/2 mt-2" />
              </CardHeader>
              <CardContent>
                <Skeleton className="h-40 w-full" />
                <Skeleton className="h-4 w-full mt-4" />
                <Skeleton className="h-4 w-full mt-2" />
                <Skeleton className="h-4 w-3/4 mt-2" />
              </CardContent>
            </>
          ) : (
            <>
              <CardHeader>
                <CardTitle>{obituary?.name}</CardTitle>
                <CardDescription>
                  {obituary?.location} | {obituary?.funeralHome}
                </CardDescription>
              </CardHeader>
              
              {obituary?.imageUrl && (
                <div className="px-6">
                  <div className="relative w-full rounded-lg overflow-hidden aspect-video mb-4">
                    <img 
                      src={obituary.imageUrl} 
                      alt={`Photo of ${obituary.name}`}
                      className="object-cover w-full h-full"
                    />
                  </div>
                </div>
              )}
              
              <CardContent>
                <div className="mb-4 text-sm text-gray-500">
                  <p>Date of Death: {obituary?.dateOfDeath}</p>
                  {obituary?.age && <p>Age: {obituary.age} years</p>}
                </div>
                
                <div className="prose max-w-none">
                  <p>{obituary?.description}</p>
                </div>
              </CardContent>
              
              <CardFooter className="flex justify-between">
                <Button asChild>
                  <Link to="/">View All Obituaries</Link>
                </Button>
                
                {obituary?.sourceUrl && (
                  <Button variant="outline" asChild>
                    <a href={obituary.sourceUrl} target="_blank" rel="noopener noreferrer">
                      View Original Source
                    </a>
                  </Button>
                )}
              </CardFooter>
            </>
          )}
        </Card>
      </main>
    </div>
  );
};

export default ObituaryShare;
